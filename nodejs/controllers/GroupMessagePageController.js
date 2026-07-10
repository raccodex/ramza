const funcs = require('../functions/functions')
const compiledTemplates = require('../compiledTemplates/compiledTemplates')
const socketEvents = require('../events/events')
const { Sequelize, Op, DataTypes } = require("sequelize");
const striptags = require('striptags');
const moment = require("moment")

const GroupMessagePageController = async (ctx, data, io,socket,callback) => {
  if ((!data.msg || data.msg.trim() === "") && !data.mediaId) {
      console.log("Message has no text, neither media, skipping")
      return
  }
  if(data.msg){
      data.msg = await funcs.cleanBR(data.msg);
  }
  let usersInGroup = await ctx.wo_groupchatusers.findAll({
      attributes: ["user_id"],
      where: {
          group_id: {
              [Op.eq]: data.group_id
          }
      }
  });
  if (!usersInGroup.find(user => user.user_id == ctx.userHashUserId[data.from_id])) {
      console.log("Not in group")
      return
  }

  let groupOwnerId = await ctx.wo_groupchat.findOne({
      attributes: ["user_id"],
      where: {
          group_id: {
              [Op.eq]: data.group_id
          }
      }
  })
  let messageOwner = await ctx.wo_users.findOne({
      where: {
          user_id: {
              [Op.eq]: ctx.userHashUserId[data.from_id]
          }
      }
  });

  let hasHTML = false;
  let msg;
  ({ msg, hasHTML } = funcs.Wo_Emo(data.msg))
  data.msg = msg

  let nextId = (lastId && lastId.id) ? (+lastId.id + 1) : 1
  if (!data.mediaId) {
      data.msg = await funcs.liksMatch(data.msg);
      data.msg = await funcs.mentionMatch(data.msg);
      data.msg = await funcs.hashTagsMatch(data.msg);

      let sendable_message = await funcs.Wo_Markup(ctx, data.msg);
      let temp = await compiledTemplates.messageListOwnerTrue(ctx, data, messageOwner, nextId, hasHTML, sendable_message, data.color)

      
      // if recepient has chat open then send last seen 
      if (ctx.userIdGroupChatOpen[ctx.userHashUserId[data.from_id]] && ctx.userIdGroupChatOpen[ctx.userHashUserId[data.from_id]].filter(d => d == data.group_id) ||
          ctx.userIdExtra[data.to_id] && ctx.userIdExtra[data.to_id].active_message_group_id && +ctx.userIdExtra[data.to_id].active_message_group_id === +ctx.userHashUserId[data.from_id]) {
              if (data.message_reply_id > 0) {
                data = await funcs.canSendReply(ctx, data);
              }
          var m_sent = await ctx.wo_messages.create({
              from_id: ctx.userHashUserId[data.from_id],
              group_id: data.group_id,
              text: await funcs.Wo_CensoredWords(ctx, data.msg),
              seen: 0,
              time: Math.floor(Date.now() / 1000),
              reply_id: parseInt(data.message_reply_id)
          })
          data.sent_message = m_sent;
         // await socketEvents.lastseen(ctx, socket, { seen: Math.floor(Date.now() / 1000) })
      }
      else {
          if (data.message_reply_id > 0) {
            data = await funcs.canSendReply(ctx, data);
          }
          var m_sent = await ctx.wo_messages.create({
              from_id: ctx.userHashUserId[data.from_id],
              group_id: data.group_id,
              text: await funcs.Wo_CensoredWords(ctx, data.msg),
              seen: 0,
              time: Math.floor(Date.now() / 1000),
              reply_id: parseInt(data.message_reply_id)
          })
          data.sent_message = m_sent;
      }
      callback({
          status: 200,
          html: temp,
          receiver: data.to_id,
          sender: ctx.userHashUserId[data.from_id],
          message_id: data.sent_message.id,
          time_api: data.sent_message.time,
      })
      // callback({
      //     status: 200,
      //     html: await compiledTemplates.groupListOwnerTrue(ctx, messageOwner, nextId, data, hasHTML, sendable_message, data.color)
      // })
      // await socketEvents.groupMessage(ctx, io, socket, data, messageOwner, nextId, hasHTML, sendable_message);
      await socketEvents.groupMessage(ctx, io, socket, data, messageOwner, nextId, hasHTML, sendable_message);
      await socketEvents.groupMessagePage(ctx, io, socket, data, messageOwner, nextId, hasHTML, sendable_message)
      await socketEvents.updateMessageGroupsList(ctx, io, ctx.userHashUserId[data.from_id])
      
  } else {
      await socketEvents.groupMessageWithMedia(ctx, io, socket, data, messageOwner, nextId, data.isSticker);
      await socketEvents.groupMessagePageWithMedia(ctx, io, socket, data, messageOwner, nextId, data.isSticker);
      await socketEvents.updateMessageGroupsList(ctx, io, ctx.userHashUserId[data.from_id])
  }

  await socketEvents.emitUserStatus(ctx, socket, ctx.userHashUserId[data.from_id])
  await socketEvents.updateMessageGroupsList(ctx, io, ctx.userHashUserId[data.from_id])
  await socketEvents.emitUserStatus(ctx, socket, data.group_id)
};

module.exports = { GroupMessagePageController };